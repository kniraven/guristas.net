<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/services/FrontlinesService.php';
try {
    $reflection=new ReflectionClass(GuristasFrontlinesService::class);
    $service=$reflection->newInstanceWithoutConstructor();
    $method=$reflection->getMethod('parseGuristasSystems');
    $method->setAccessible(true);
    $fixture=json_decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/frontlines-campaigns.json'),true,512,JSON_THROW_ON_ERROR);
    $parsed=$method->invoke($service,$fixture);
    $expected=[
        30045339=>['Enaluri',1,10.0,0,2.0,false],
        30045340=>['Aivonen',0,4.0,1,10.5,false],
        30045341=>['Hallanen',1,17.5,0,0.0,false],
        30045342=>['Akidagi',0,2.5,0,5.75,true],
        30045344=>['Nennamaila',0,3.75,0,5.0,false],
    ];
    if (count($parsed['systems'])!==5 || $parsed['diagnostics']['parser_version']!==3) throw new RuntimeException('Expected five Guristas systems from the current parser.');
    foreach ($parsed['systems'] as $system) {
        $actual=[$system['name'],$system['corruption_stage'],$system['corruption_percent'],$system['suppression_stage'],$system['suppression_percent'],$system['is_fob']];
        if (!isset($expected[$system['system_id']]) || $actual!==$expected[$system['system_id']]) throw new RuntimeException('Campaign values or faction filtering changed.');
        unset($expected[$system['system_id']]);
    }
    if ($expected!==[]) throw new RuntimeException('A Guristas system was lost.');
    $empty=$method->invoke($service,[]);
    if ($empty['systems']!==[]) throw new RuntimeException('Empty campaign list must be accepted.');
    $angelOnly=$method->invoke($service,[['pirateFactionId'=>500011,'insurgencies'=>[]]]);
    $noSystems=$method->invoke($service,[['pirateFactionId'=>500010,'insurgencies'=>[]]]);
    if ($angelOnly['systems']!==[] || $noSystems['systems']!==[]) throw new RuntimeException('Valid feeds with no Guristas systems must be accepted.');
    foreach ([
        [['solarsystemID'=>30045342,'ownerFaction'=>500001,'contestedAmount'=>0.1]],
        [['factionId'=>500010,'systems'=>[['systemId'=>30045342,'corruptionLevel'=>5]]]],
        ['error'=>'unavailable'],
    ] as $unsupported) {
        try { $method->invoke($service,$unsupported); }
        catch (RuntimeException $expectedError) { continue; }
        throw new RuntimeException('Unsupported feed was accepted.');
    }
    $topology=$reflection->getMethod('topology');
    $topology->setAccessible(true);
    $emptyTopology=$topology->invoke($service,[]);
    if ($emptyTopology['data']!==['systems'=>[],'edges'=>[]]) throw new RuntimeException('Empty topology changed.');
    echo "Frontlines parser checks passed: campaign values, faction filtering, FOB, zero metrics, empty and unsupported feeds.\n";
} catch (Throwable $error) {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
