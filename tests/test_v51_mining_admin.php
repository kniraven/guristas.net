<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/BuildIntelligence.php';
foreach([['ore','Arkonor',3386],['ice','Gelidus',16281],['gas','Viridian Mykoserocin',25544],['ore','Mercoxit',11395]] as [$category,$name,$expected]){
 $skill=GuristasBuildIntelligence::resourceHarvestSkill($category,$name);
 if($skill['id']!==$expected||$skill['level']!==1)throw new RuntimeException('Incorrect derived skill '.$name);
}
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/public/admin/index.php');
$editor=file_get_contents($root.'/public/admin/mining/index.php');
$js=file_get_contents($root.'/public/assets/js/mining-admin.js');
$engine=file_get_contents($root.'/app/services/BuildIntelligence.php');
foreach(['<div class="ticket-toolbar"><a class="button" href="/admin/mining/">', 'class="button" href="/admin/tickets/?staff=1"'] as $part)if(!str_contains($admin,$part))throw new RuntimeException('Admin theme link contract failed');
if(!str_contains($editor,"$".'harvest=GuristasBuildIntelligence::resourceHarvestSkill')||str_contains($editor,'name="skill_id"'))throw new RuntimeException('Editor must derive harvesting skill');
if(!str_contains($js,'showHarvestSkill('))throw new RuntimeException('Client skill display missing');
if(!str_contains($engine,"$"."entry['min_skill']"))throw new RuntimeException('BUILD skill integration missing');
echo "PASS v51: shared harvesting skill rules, BUILD integration, read-only editor skill, themed Admin links\n";
